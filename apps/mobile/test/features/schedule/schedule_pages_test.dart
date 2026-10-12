import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';
import 'package:http/http.dart' as http;

import 'package:mobile/app/app.dart';
import 'package:mobile/features/auth/state/auth_controller.dart';
import 'package:mobile/features/schedule/presentation/schedule_entry_page.dart';
import 'package:mobile/features/schedule/presentation/schedule_page.dart';
import 'package:mobile/features/tasks/presentation/task_detail_page.dart';

import '../../support/fake_backend.dart';
import '../../support/home_fixtures.dart' show homeClientFor, homeResponse;
import '../../support/schedule_fixtures.dart';
import '../../support/task_fixtures.dart';

/// Phase 30A Gate 3 — the Schedule tab and the entry detail through the
/// real app (`CompanyApp`, router, shell, `ApiClient`), against an
/// in-memory `/me/schedule` whose company today is Wed 14 Oct in Manila
/// (docs/phases/V1_PHASE_30_DEFINITION.md §5.3, R-8…R-11).
void main() {
  late FakeBackend backend;
  late AuthController auth;
  late List<http.Request> requests;

  /// Every item the server knows; each response returns those overlapping
  /// the requested range (a lenient stand-in: tests choose items whose
  /// days are unambiguous).
  late List<Map<String, dynamic>>? items;

  /// When set, answers `GET /me/schedule` instead of [items].
  Future<http.Response> Function(Uri url)? onSchedule;

  /// When set, answers `GET /schedule-entries/{id}`.
  Future<http.Response> Function(String id)? onEntry;

  bool inRange(Map<String, dynamic> item, String from, String to) {
    final start = DateTime.parse(item['starts_at'] as String)
        .toUtc()
        .add(const Duration(hours: 8));
    final end = DateTime.parse(item['ends_at'] as String)
        .toUtc()
        .add(const Duration(hours: 8));
    String date(DateTime d) => d.toIso8601String().substring(0, 10);

    return date(start).compareTo(to) <= 0 && date(end).compareTo(from) >= 0;
  }

  Future<http.Response> route(http.Request request) async {
    requests.add(request);
    final path = request.url.path;

    if (path.endsWith('/me/home')) {
      return homeResponse();
    }
    if (path.endsWith('/me/schedule')) {
      if (onSchedule case final custom?) {
        return custom(request.url);
      }
      final from = request.url.queryParameters['from']!;
      final to = request.url.queryParameters['to']!;

      return jsonResponse(
        myScheduleJson(
          items?.where((i) => inRange(i, from, to)).toList(),
          from: from,
          to: to,
        ),
      );
    }
    if (path.contains('/schedule-entries/')) {
      final id = path.split('/').last;
      if (onEntry case final custom?) {
        return custom(id);
      }

      return jsonResponse({'data': scheduleEntryJson(publicId: id)});
    }
    if (path.contains('/tasks/')) {
      return jsonResponse({'data': taskJson(publicId: path.split('/').last)});
    }
    if (path.endsWith('/me/tasks')) {
      return jsonResponse(myTasksBody([]));
    }

    fail('unexpected request: ${request.url}');
  }

  setUp(() {
    items = [
      scheduleItemJson(
        publicId: 'E-site',
        title: 'Site visit',
        activityType: 'client_visit',
        project: {'public_id': 'P1', 'name': 'Plant Room'},
      ),
      manilaTaskJson('2026-10-14', id: 'T-filters'),
      scheduleItemJson(
        source: 'leave',
        publicId: 'L-annual',
        title: 'Annual leave',
        startsAt: '2026-10-15T16:00:00+00:00',
        endsAt: '2026-10-17T15:59:59+00:00',
        isAllDay: true,
        status: 'approved',
      ),
      // 23:00 Mon 26 Oct – 01:00 Tue 27 Oct Manila: next week.
      scheduleItemJson(
        publicId: 'E-night',
        title: 'Night shift',
        startsAt: '2026-10-26T15:00:00+00:00',
        endsAt: '2026-10-26T17:00:00+00:00',
      ),
    ];
    requests = [];
    onSchedule = null;
    onEntry = null;
  });

  // A gesture that misses its target must fail the test.
  setUpAll(() => WidgetController.hitTestWarningShouldBeFatal = true);
  tearDownAll(() => WidgetController.hitTestWarningShouldBeFatal = false);

  Future<void> pumpApp(WidgetTester tester, {bool tall = true}) async {
    if (tall) {
      tester.view.physicalSize =
          const Size(800, 3200) * tester.view.devicePixelRatio;
      addTearDown(tester.view.resetPhysicalSize);
    }
    backend = FakeBackend()..onApi = route;
    auth = backend.controller(RecordingTokenStorage(initialToken: 'token-a'));

    await tester.pumpWidget(
      CompanyApp(
        authController: auth,
        homeApiClient: homeClientFor(backend, auth),
        tasksApiClient: tasksClientFor(backend, auth),
        scheduleApiClient: scheduleClientFor(backend, auth),
      ),
    );
    await tester.pumpAndSettle();
  }

  Future<void> openSchedule(WidgetTester tester) async {
    await tester.tap(find.widgetWithText(NavigationDestination, 'Schedule'));
    await tester.pumpAndSettle();
  }

  Finder item(String date, String id) =>
      find.byKey(Key('schedule-item-$date-$id'));

  Finder inItem(String date, String id, Finder matching) =>
      find.descendant(of: item(date, id), matching: matching);

  String weekLabel(WidgetTester tester) =>
      tester.widget<Text>(find.byKey(const Key('schedule-week'))).data!;

  int selectedTab(WidgetTester tester) =>
      tester.widget<NavigationBar>(find.byType(NavigationBar)).selectedIndex;

  List<Uri> scheduleRequests() => [
    for (final r in requests)
      if (r.url.path.endsWith('/me/schedule')) r.url,
  ];

  group('week agenda', () {
    testWidgets(
      'seven company days from today, labelled, with items in server order',
      (tester) async {
        await pumpApp(tester);
        await openSchedule(tester);

        expect(find.byType(SchedulePage), findsOneWidget);
        expect(weekLabel(tester), '14 – 20 Oct');
        final labels = <String, String>{
          '2026-10-14': 'Today',
          '2026-10-15': 'Tomorrow',
          '2026-10-16': 'Fri 16 Oct',
          '2026-10-17': 'Sat 17 Oct',
          '2026-10-18': 'Sun 18 Oct',
          '2026-10-19': 'Mon 19 Oct',
          '2026-10-20': 'Tue 20 Oct',
        };
        for (final MapEntry(key: date, value: label) in labels.entries) {
          expect(
            find.descendant(
              of: find.byKey(Key('schedule-day-$date')),
              matching: find.text(label),
            ),
            findsOneWidget,
            reason: date,
          );
        }
        expect(find.byKey(const Key('schedule-day-2026-10-13')), findsNothing);
        expect(find.byKey(const Key('schedule-day-2026-10-21')), findsNothing);

        // Today: the entry in company time with its type and project,
        // then the task with its status.
        expect(
          inItem(
            '2026-10-14',
            'E-site',
            find.text('09:00 – 10:00 · Client visit · Plant Room'),
          ),
          findsOneWidget,
        );
        expect(
          inItem(
            '2026-10-14',
            'T-filters',
            find.text('All day · Task · To do'),
          ),
          findsOneWidget,
        );
        final siteY = tester.getTopLeft(item('2026-10-14', 'E-site')).dy;
        final taskY = tester.getTopLeft(item('2026-10-14', 'T-filters')).dy;
        expect(siteY, lessThan(taskY));

        // Leave spans 16–17 Oct Manila and appears on both days only.
        for (final date in ['2026-10-16', '2026-10-17']) {
          expect(
            inItem(date, 'L-annual', find.text('All day · Leave')),
            findsOneWidget,
          );
        }
        expect(item('2026-10-15', 'L-annual'), findsNothing);
        expect(item('2026-10-18', 'L-annual'), findsNothing);

        // Empty days say so.
        for (final date in [
          '2026-10-15',
          '2026-10-18',
          '2026-10-19',
          '2026-10-20',
        ]) {
          expect(
            find.byKey(Key('schedule-empty-$date')),
            findsOneWidget,
            reason: date,
          );
        }
        expect(
          find.byKey(const Key('schedule-empty-2026-10-14')),
          findsNothing,
        );
        expect(find.text('Nothing scheduled.'), findsNWidgets(4));
      },
    );

    testWidgets('Next, Previous and Today move by whole company weeks', (
      tester,
    ) async {
      await pumpApp(tester);
      await openSchedule(tester);
      final today = find.byKey(const Key('schedule-today'));
      expect(tester.widget<TextButton>(today).onPressed, isNull);

      await tester.tap(find.byTooltip('Next week'));
      await tester.pumpAndSettle();
      expect(weekLabel(tester), '21 – 27 Oct');
      expect(scheduleRequests().last.queryParameters, {
        'from': '2026-10-21',
        'to': '2026-10-27',
      });
      expect(find.text('Wed 21 Oct'), findsOneWidget);
      expect(find.text('Today'), findsOneWidget); // only the AppBar button
      // An overnight entry is on both of its company days.
      expect(
        inItem(
          '2026-10-26',
          'E-night',
          find.text('23:00 – until Tue 27 Oct · Meeting'),
        ),
        findsOneWidget,
      );
      expect(
        inItem(
          '2026-10-27',
          'E-night',
          find.text('From Mon 26 Oct – 01:00 · Meeting'),
        ),
        findsOneWidget,
      );
      expect(tester.widget<TextButton>(today).onPressed, isNotNull);

      await tester.tap(today);
      await tester.pumpAndSettle();
      expect(weekLabel(tester), '14 – 20 Oct');
      expect(tester.widget<TextButton>(today).onPressed, isNull);

      await tester.tap(find.byTooltip('Previous week'));
      await tester.pumpAndSettle();
      expect(weekLabel(tester), '7 – 13 Oct');
      expect(scheduleRequests().last.queryParameters, {
        'from': '2026-10-07',
        'to': '2026-10-13',
      });
      expect(
        find.descendant(
          of: find.byKey(const Key('schedule-day-2026-10-13')),
          matching: find.text('Yesterday'),
        ),
        findsOneWidget,
      );

      await tester.tap(find.byTooltip('Previous week'));
      await tester.pumpAndSettle();
      expect(weekLabel(tester), '30 Sep – 6 Oct');
    });

    testWidgets('pull to refresh reloads the shown week', (tester) async {
      await pumpApp(tester);
      await openSchedule(tester);
      await tester.tap(find.byTooltip('Next week'));
      await tester.pumpAndSettle();
      final before = scheduleRequests().length;
      items = [
        ...items!,
        scheduleItemJson(
          publicId: 'E-new',
          title: 'New review',
          startsAt: '2026-10-22T02:00:00+00:00',
          endsAt: '2026-10-22T03:00:00+00:00',
        ),
      ];

      await tester.fling(
        find.byKey(const Key('schedule-list')),
        // RefreshIndicator arms at 25% of the (tall) viewport.
        const Offset(0, 1200),
        1000,
      );
      await tester.pumpAndSettle();

      expect(scheduleRequests().length, before + 1);
      expect(scheduleRequests().last.queryParameters['from'], '2026-10-21');
      expect(item('2026-10-22', 'E-new'), findsOneWidget);
      expect(weekLabel(tester), '21 – 27 Oct');
    });

    testWidgets('a failed refresh keeps the week on screen and says so', (
      tester,
    ) async {
      await pumpApp(tester);
      await openSchedule(tester);
      onSchedule = (_) async => throw const NetworkFailure();

      await tester.fling(
        find.byKey(const Key('schedule-list')),
        // RefreshIndicator arms at 25% of the (tall) viewport.
        const Offset(0, 1200),
        1000,
      );
      await tester.pumpAndSettle();

      expect(item('2026-10-14', 'E-site'), findsOneWidget);
      expect(find.byType(SnackBar), findsOneWidget);
    });
  });

  group('states', () {
    testWidgets('no linked profile says so instead of an empty week', (
      tester,
    ) async {
      items = null;
      await pumpApp(tester);
      await openSchedule(tester);

      expect(
        find.text('No staff profile is linked to this account.'),
        findsOneWidget,
      );
      expect(find.byKey(const Key('schedule-list')), findsNothing);
      expect(find.text('Nothing scheduled.'), findsNothing);
    });

    testWidgets('a first-load failure shows the error; Try again recovers', (
      tester,
    ) async {
      onSchedule = (_) async => throw const NetworkFailure();
      await pumpApp(tester);
      await openSchedule(tester);

      expect(
        find.text("Couldn't load your schedule. Check your connection."),
        findsOneWidget,
      );
      expect(find.byKey(const Key('schedule-today')), findsNothing);

      onSchedule = null;
      await tester.tap(find.text('Try again'));
      await tester.pumpAndSettle();

      expect(weekLabel(tester), '14 – 20 Oct');
      expect(item('2026-10-14', 'E-site'), findsOneWidget);
    });

    testWidgets('a failure on another week retries that week', (tester) async {
      await pumpApp(tester);
      await openSchedule(tester);
      onSchedule = (_) async => jsonError(500, 'Server error.');

      await tester.tap(find.byTooltip('Next week'));
      await tester.pumpAndSettle();
      expect(
        find.text('Something went wrong loading your schedule.'),
        findsOneWidget,
      );

      onSchedule = null;
      await tester.tap(find.text('Try again'));
      await tester.pumpAndSettle();

      expect(weekLabel(tester), '21 – 27 Oct');
      expect(scheduleRequests().last.queryParameters['from'], '2026-10-21');
    });

    testWidgets('a 401 leaves the shell for Login', (tester) async {
      onSchedule = (_) async => jsonError(401);
      await pumpApp(tester);
      await openSchedule(tester);

      expect(find.byType(SchedulePage), findsNothing);
      expect(find.text('Sign in'), findsWidgets);
    });
  });

  group('navigation (R-9, R-10)', () {
    testWidgets('a task opens its detail in the Tasks tab', (tester) async {
      await pumpApp(tester);
      await openSchedule(tester);

      await tester.tap(item('2026-10-14', 'T-filters'));
      await tester.pumpAndSettle();

      expect(find.byType(TaskDetailPage), findsOneWidget);
      expect(
        GoRouter.of(tester.element(find.byType(TaskDetailPage)))
            .state
            .matchedLocation,
        '/tasks/T-filters',
      );
      expect(selectedTab(tester), 1);
    });

    testWidgets('leave is not tappable and has no chevron', (tester) async {
      await pumpApp(tester);
      await openSchedule(tester);

      expect(
        tester
            .widget<ListTile>(
              inItem('2026-10-16', 'L-annual', find.byType(ListTile)),
            )
            .onTap,
        isNull,
      );
      expect(
        inItem('2026-10-16', 'L-annual', find.byIcon(Icons.chevron_right)),
        findsNothing,
      );
      expect(
        inItem('2026-10-14', 'E-site', find.byIcon(Icons.chevron_right)),
        findsOneWidget,
      );
      expect(
        inItem('2026-10-14', 'T-filters', find.byIcon(Icons.chevron_right)),
        findsOneWidget,
      );
    });

    testWidgets(
      'an entry opens its read-only detail in company time; back keeps the week',
      (tester) async {
        await pumpApp(tester);
        await openSchedule(tester);
        await tester.tap(find.byTooltip('Next week'));
        await tester.pumpAndSettle();
        final before = scheduleRequests().length;

        await tester.tap(item('2026-10-26', 'E-night'));
        await tester.pumpAndSettle();

        expect(find.byType(ScheduleEntryPage), findsOneWidget);
        expect(
          GoRouter.of(tester.element(find.byType(ScheduleEntryPage)))
              .state
              .matchedLocation,
          '/schedule/entries/E-night',
        );
        expect(selectedTab(tester), 2);
        final header = find.byKey(const Key('schedule-entry-header'));
        expect(
          find.descendant(of: header, matching: find.text('Site visit')),
          findsOneWidget,
        );
        expect(
          find.descendant(of: header, matching: find.text('Client visit')),
          findsOneWidget,
        );
        // 01:00–02:30 UTC is 09:00–10:30 in Manila.
        expect(
          find.descendant(
            of: find.byKey(const Key('schedule-entry-when')),
            matching: find.text('Wed 14 Oct · 09:00 – 10:30'),
          ),
          findsOneWidget,
        );
        expect(find.text('Plant Room'), findsOneWidget);
        expect(find.text('Ada Lovelace'), findsOneWidget);
        expect(find.text('People (1)'), findsOneWidget);
        expect(
          find.byKey(const Key('schedule-entry-person-S2')),
          findsOneWidget,
        );
        expect(find.text('Bring the keys.'), findsOneWidget);
        // Read-only: nothing to press but Back.
        for (final type in [
          FilledButton,
          OutlinedButton,
          TextButton,
          TextField,
          Switch,
        ]) {
          expect(
            find.descendant(
              of: find.byType(ScheduleEntryPage),
              matching: find.byType(type),
            ),
            findsNothing,
            reason: '$type',
          );
        }

        await tester.pageBack();
        await tester.pumpAndSettle();
        expect(find.byType(ScheduleEntryPage), findsNothing);
        expect(weekLabel(tester), '21 – 27 Oct');
        expect(scheduleRequests().length, before);
      },
    );

    testWidgets('an entry with no other people and no description', (
      tester,
    ) async {
      onEntry = (id) async => jsonResponse({
        'data': {
          ...scheduleEntryJson(publicId: id, participants: <Object>[]),
          'description': '  ',
          'project': null,
          'creator': null,
        },
      });
      await pumpApp(tester);
      await openSchedule(tester);

      await tester.tap(item('2026-10-14', 'E-site'));
      await tester.pumpAndSettle();

      expect(find.text('People (0)'), findsOneWidget);
      expect(find.text('No other people.'), findsOneWidget);
      expect(find.byKey(const Key('schedule-entry-description')), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('an entry that is gone says so and Try again reloads', (
      tester,
    ) async {
      var calls = 0;
      onEntry = (id) async {
        calls++;

        return jsonError(404, 'Not found.');
      };
      await pumpApp(tester);
      await openSchedule(tester);

      await tester.tap(item('2026-10-14', 'E-site'));
      await tester.pumpAndSettle();

      expect(find.text('This item is no longer available.'), findsOneWidget);
      await tester.tap(find.text('Try again'));
      await tester.pumpAndSettle();
      expect(calls, 2);
    });

    testWidgets('a deep link without arguments shows device time and says so', (
      tester,
    ) async {
      await pumpApp(tester);
      GoRouter.of(tester.element(find.byType(NavigationBar)))
          .go('/schedule/entries/E-site');
      await tester.pumpAndSettle();

      expect(find.byType(ScheduleEntryPage), findsOneWidget);
      expect(find.textContaining('(device time)'), findsOneWidget);
    });
  });

  group('appearance', () {
    for (final (label, brightness) in [
      ('light', Brightness.light),
      ('dark', Brightness.dark),
    ]) {
      testWidgets(
        'agenda and detail render on a small phone in $label mode at 200% text',
        (tester) async {
          tester.platformDispatcher.platformBrightnessTestValue = brightness;
          tester.platformDispatcher.textScaleFactorTestValue = 2.0;
          addTearDown(tester.platformDispatcher.clearAllTestValues);
          tester.view.physicalSize =
              const Size(320, 568) * tester.view.devicePixelRatio;
          addTearDown(tester.view.resetPhysicalSize);
          items = [
            ...items!,
            scheduleItemJson(
              publicId: 'E-long',
              title:
                  'A very long meeting title that keeps going well past one '
                  'line to check wrapping',
              project: {
                'public_id': 'P2',
                'name': 'A project with a long name as well',
              },
            ),
          ];

          await pumpApp(tester, tall: false);
          await openSchedule(tester);
          expect(tester.takeException(), isNull);
          expect(find.byKey(const Key('schedule-week')), findsOneWidget);
          expect(
            Theme.of(tester.element(find.byType(SchedulePage))).brightness,
            brightness,
          );

          await tester.ensureVisible(item('2026-10-14', 'E-site'));
          await tester.pumpAndSettle();
          await tester.tap(item('2026-10-14', 'E-site'));
          await tester.pumpAndSettle();
          expect(tester.takeException(), isNull);
          expect(find.byType(ScheduleEntryPage), findsOneWidget);
        },
      );
    }

    testWidgets('week controls meet tap-target and label guidelines', (
      tester,
    ) async {
      final semantics = tester.ensureSemantics();
      await pumpApp(tester);
      await openSchedule(tester);

      await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
      await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
      semantics.dispose();
    });
  });
}
